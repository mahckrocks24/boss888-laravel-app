<?php

namespace App\Engines\Infrastructure\States;

/**
 * Lifecycle of a single governed infrastructure operation.
 *
 * PHASE 1B — expanded from the Phase 1A shape to cover the full governed flow:
 *
 *   requested → awaiting_approval → approved → queued → running → succeeded
 *
 * Failure branches: rejected, cancelled, failed_retryable, failed_terminal,
 * timed_out, compensation_pending, compensated.
 *
 * Two distinctions that are load-bearing, not decorative:
 *
 *   failed_retryable vs failed_terminal — the reaper may re-queue the first and
 *   must never re-queue the second. Collapsing them means either giving up on
 *   transient provider errors or retrying a permanently invalid request forever.
 *
 *   timed_out — we do NOT know whether the provider applied the change. It
 *   therefore leads to compensation_pending (reconcile against provider truth),
 *   not straight back to queued. Blind retry after a timeout is how duplicate
 *   provisioning happens.
 */
final class OperationState extends StateMachine
{
    public const REQUESTED            = 'requested';
    public const AWAITING_APPROVAL    = 'awaiting_approval';
    public const APPROVED             = 'approved';
    public const QUEUED               = 'queued';
    public const RUNNING              = 'running';
    public const SUCCEEDED            = 'succeeded';
    public const REJECTED             = 'rejected';
    public const CANCELLED            = 'cancelled';
    public const FAILED_RETRYABLE     = 'failed_retryable';
    public const FAILED_TERMINAL      = 'failed_terminal';
    public const TIMED_OUT            = 'timed_out';
    public const COMPENSATION_PENDING = 'compensation_pending';
    public const COMPENSATED          = 'compensated';

    public static function initial(): string
    {
        return self::REQUESTED;
    }

    public static function states(): array
    {
        return [
            self::REQUESTED,
            self::AWAITING_APPROVAL,
            self::APPROVED,
            self::QUEUED,
            self::RUNNING,
            self::SUCCEEDED,
            self::REJECTED,
            self::CANCELLED,
            self::FAILED_RETRYABLE,
            self::FAILED_TERMINAL,
            self::TIMED_OUT,
            self::COMPENSATION_PENDING,
            self::COMPENSATED,
        ];
    }

    public static function transitions(): array
    {
        return [
            // An auto-approved capability may go straight to approved; a
            // protected one must pass through awaiting_approval.
            self::REQUESTED            => [self::AWAITING_APPROVAL, self::APPROVED, self::REJECTED, self::CANCELLED],
            self::AWAITING_APPROVAL    => [self::APPROVED, self::REJECTED, self::CANCELLED],
            self::APPROVED             => [self::QUEUED, self::CANCELLED],
            self::QUEUED               => [self::RUNNING, self::CANCELLED, self::TIMED_OUT],
            self::RUNNING              => [self::SUCCEEDED, self::FAILED_RETRYABLE, self::FAILED_TERMINAL, self::TIMED_OUT],
            // Retry re-enters the queue; exhausting attempts is terminal.
            self::FAILED_RETRYABLE     => [self::QUEUED, self::FAILED_TERMINAL, self::CANCELLED],
            // Unknown provider outcome -> reconcile, never blind retry.
            self::TIMED_OUT            => [self::COMPENSATION_PENDING, self::FAILED_TERMINAL],
            self::COMPENSATION_PENDING => [self::COMPENSATED, self::FAILED_TERMINAL],

            self::SUCCEEDED            => [],
            self::REJECTED             => [],
            self::CANCELLED            => [],
            self::FAILED_TERMINAL      => [],
            self::COMPENSATED          => [],
        ];
    }

    public static function terminal(): array
    {
        return [
            self::SUCCEEDED,
            self::REJECTED,
            self::CANCELLED,
            self::FAILED_TERMINAL,
            self::COMPENSATED,
        ];
    }

    /** States the reaper investigates (directive §14). */
    public static function recoverable(): array
    {
        return [self::QUEUED, self::RUNNING, self::COMPENSATION_PENDING];
    }

    /** States in which no provider call has been made yet. */
    public static function preExecution(): array
    {
        return [self::REQUESTED, self::AWAITING_APPROVAL, self::APPROVED, self::QUEUED];
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::terminal(), true);
    }
}
