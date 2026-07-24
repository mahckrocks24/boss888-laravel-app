<?php

namespace App\Engines\Infrastructure\States;

use InvalidArgumentException;

/**
 * How a commercial limit behaves when it is reached.
 *
 * NOT a state machine — a classification. It exists because the central mistake
 * in hosting commercials is treating every limit identically.
 *
 *   HARD           refuse the action. Correct for discrete provisioning
 *                  (websites, mailboxes, domains) where each unit has a real
 *                  provider cost.
 *   SOFT           allow, record, warn. Never break a running site.
 *   BILLABLE       allow, record, and charge the overage. Correct for storage:
 *                  refusing writes at 100.1% of quota breaks live sites and
 *                  generates churn, so bill it instead.
 *   WARNING        allow and notify only. Manage by relationship, not by meter.
 *   ADMINISTRATIVE internal policy, not a customer-facing lever (e.g. backup
 *                  retention). Never surfaced as an upgrade prompt.
 *   NONE           unmetered.
 */
final class UsageBehavior
{
    public const HARD           = 'hard';
    public const SOFT           = 'soft';
    public const BILLABLE       = 'billable';
    public const WARNING        = 'warning';
    public const ADMINISTRATIVE = 'administrative';
    public const NONE           = 'none';

    public static function all(): array
    {
        return [self::HARD, self::SOFT, self::BILLABLE, self::WARNING, self::ADMINISTRATIVE, self::NONE];
    }

    public static function isValid(string $behavior): bool
    {
        return in_array($behavior, self::all(), true);
    }

    public static function assertValid(string $behavior): void
    {
        if (!self::isValid($behavior)) {
            throw new InvalidArgumentException("Unknown usage behaviour '{$behavior}'.");
        }
    }

    /** Behaviours that must block the action when the limit is reached. */
    public static function blocking(): array
    {
        return [self::HARD];
    }

    /** Behaviours that produce a charge. */
    public static function chargeable(): array
    {
        return [self::BILLABLE];
    }

    /** Behaviours that should notify the customer. */
    public static function notifying(): array
    {
        return [self::SOFT, self::BILLABLE, self::WARNING];
    }

    /** Behaviours never shown to a customer as an upgrade prompt. */
    public static function internalOnly(): array
    {
        return [self::ADMINISTRATIVE];
    }

    /**
     * The default classification per metric, per the Phase 2A usage model.
     * A plan may override via infra_plan_entitlements.behavior.
     */
    public static function defaultForMetric(string $metric): string
    {
        return match ($metric) {
            'site_count', 'mailbox_count', 'domain_count' => self::HARD,
            'storage_mb'                                   => self::BILLABLE,
            'bandwidth_mb'                                 => self::SOFT,
            'restore_count'                                => self::SOFT,
            'migration_count'                              => self::HARD,
            'backup_retention_days'                        => self::ADMINISTRATIVE,
            default                                        => self::NONE,
        };
    }
}
