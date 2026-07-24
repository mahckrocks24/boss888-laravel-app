<?php

namespace App\Engines\Infrastructure\States;

/**
 * Commercial lifecycle of an infrastructure subscription.
 *
 * Phase 2A-1 adds TRIAL and ARCHIVED.
 *
 * `cancelled` and `archived` are deliberately distinct:
 *   cancelled = no longer served, data retained per policy
 *   archived  = retention elapsed, data removed
 * Conflating them is how a host either deletes a customer's data early or keeps
 * it for years and inherits a compliance problem.
 *
 * `prospect` is intentionally ABSENT — that is a CRM state, not a subscription
 * state. The machine begins at draft or trial.
 *
 * Mode A (manual contract, staff-issued invoice) enters at PENDING_PROVISIONING
 * without passing through PENDING_PAYMENT, because payment happened off-platform.
 */
final class SubscriptionState extends StateMachine
{
    public const DRAFT                = 'draft';
    public const TRIAL                = 'trial';
    public const PENDING_PAYMENT      = 'pending_payment';
    public const PENDING_PROVISIONING = 'pending_provisioning';
    public const PROVISIONING         = 'provisioning';
    public const ACTIVE               = 'active';
    public const PAST_DUE             = 'past_due';
    public const GRACE_PERIOD         = 'grace_period';
    public const SUSPENDED            = 'suspended';
    public const CANCELLATION_PENDING = 'cancellation_pending';
    public const CANCELLED            = 'cancelled';
    public const ARCHIVED             = 'archived';
    public const FAILED               = 'failed';

    public static function initial(): string
    {
        return self::DRAFT;
    }

    public static function states(): array
    {
        return [
            self::DRAFT,
            self::TRIAL,
            self::PENDING_PAYMENT,
            self::PENDING_PROVISIONING,
            self::PROVISIONING,
            self::ACTIVE,
            self::PAST_DUE,
            self::GRACE_PERIOD,
            self::SUSPENDED,
            self::CANCELLATION_PENDING,
            self::CANCELLED,
            self::ARCHIVED,
            self::FAILED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::DRAFT                => [self::TRIAL, self::PENDING_PAYMENT, self::PENDING_PROVISIONING, self::CANCELLED],

            // A trial may convert (to payment or straight to provisioning under
            // Mode A) or lapse. It never becomes ACTIVE without provisioning.
            self::TRIAL                => [self::PENDING_PAYMENT, self::PENDING_PROVISIONING, self::CANCELLED, self::FAILED],

            self::PENDING_PAYMENT      => [self::PENDING_PROVISIONING, self::FAILED, self::CANCELLED],
            self::PENDING_PROVISIONING => [self::PROVISIONING, self::FAILED, self::CANCELLED],
            self::PROVISIONING         => [self::ACTIVE, self::FAILED],

            self::ACTIVE               => [self::PAST_DUE, self::CANCELLATION_PENDING, self::SUSPENDED],
            self::PAST_DUE             => [self::GRACE_PERIOD, self::ACTIVE, self::SUSPENDED],
            self::GRACE_PERIOD         => [self::ACTIVE, self::SUSPENDED],
            self::SUSPENDED            => [self::ACTIVE, self::CANCELLATION_PENDING, self::CANCELLED],
            self::CANCELLATION_PENDING => [self::CANCELLED, self::ACTIVE],

            // Retention elapses -> archived. Terminal thereafter.
            self::CANCELLED            => [self::ARCHIVED],

            // Recoverable: an operator may retry a failed provisioning.
            self::FAILED               => [self::PENDING_PROVISIONING, self::CANCELLED],

            self::ARCHIVED             => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::ARCHIVED];
    }

    /** States in which the customer's entitlement is honoured. */
    public static function entitled(): array
    {
        return [self::TRIAL, self::ACTIVE, self::PAST_DUE, self::GRACE_PERIOD, self::CANCELLATION_PENDING];
    }

    /** States that should appear in revenue reporting. */
    public static function revenueBearing(): array
    {
        return [self::ACTIVE, self::PAST_DUE, self::GRACE_PERIOD, self::CANCELLATION_PENDING];
    }

    /** A trial is entitled but generates no revenue — kept explicit. */
    public static function isTrial(string $state): bool
    {
        return $state === self::TRIAL;
    }
}
