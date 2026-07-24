<?php

namespace App\Engines\Infrastructure\States;

/**
 * Provisioning lifecycle of a hosting service entitlement.
 *
 * Note `degraded`: a hosting account that is provisioned and paid for but failing
 * health checks is NOT "active" and NOT "failed". Collapsing that third case into a
 * boolean is how the platform previously reported success for things that had not
 * happened (the social-publishing facade removed 2026-07-15).
 */
final class HostingState extends StateMachine
{
    public const REQUESTED           = 'requested';
    public const APPROVED            = 'approved';
    public const QUEUED              = 'queued';
    public const PROVISIONING        = 'provisioning';
    public const CONFIGURING         = 'configuring';
    public const ACTIVE              = 'active';
    public const DEGRADED            = 'degraded';
    public const SUSPENDED           = 'suspended';
    public const TERMINATION_PENDING = 'termination_pending';
    public const TERMINATED          = 'terminated';
    public const FAILED              = 'failed';

    public static function initial(): string
    {
        return self::REQUESTED;
    }

    public static function states(): array
    {
        return [
            self::REQUESTED,
            self::APPROVED,
            self::QUEUED,
            self::PROVISIONING,
            self::CONFIGURING,
            self::ACTIVE,
            self::DEGRADED,
            self::SUSPENDED,
            self::TERMINATION_PENDING,
            self::TERMINATED,
            self::FAILED,
        ];
    }

    public static function transitions(): array
    {
        return [
            self::REQUESTED           => [self::APPROVED, self::FAILED],
            self::APPROVED            => [self::QUEUED, self::FAILED],
            self::QUEUED              => [self::PROVISIONING, self::FAILED],
            self::PROVISIONING        => [self::CONFIGURING, self::FAILED],
            self::CONFIGURING         => [self::ACTIVE, self::FAILED],
            self::ACTIVE              => [self::DEGRADED, self::SUSPENDED, self::TERMINATION_PENDING],
            self::DEGRADED            => [self::ACTIVE, self::SUSPENDED, self::TERMINATION_PENDING, self::FAILED],
            self::SUSPENDED           => [self::ACTIVE, self::TERMINATION_PENDING],
            self::TERMINATION_PENDING => [self::TERMINATED, self::ACTIVE],
            self::FAILED              => [self::QUEUED, self::TERMINATION_PENDING],
            self::TERMINATED          => [],
        ];
    }

    public static function terminal(): array
    {
        return [self::TERMINATED];
    }

    public static function operational(): array
    {
        return [self::ACTIVE, self::DEGRADED];
    }
}
