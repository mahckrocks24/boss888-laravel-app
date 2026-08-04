<?php

namespace App\Engines\Studio\Projection;

/**
 * STUDIO888 · Projection — idempotency replay state.
 *
 * Describes how a request/batch relates to a prior submission of the same
 * idempotency key:
 *   FIRST       - never seen; applied now
 *   COMPLETED   - already applied; the cached result is returned, NOT re-applied
 *   IN_PROGRESS - a prior submission is still running; do not apply
 *   EXPIRED     - the idempotency record aged out; a fresh key is required
 */
final class ReplayState
{
    public const FIRST       = 'first';
    public const COMPLETED   = 'completed';
    public const IN_PROGRESS = 'in_progress';
    public const EXPIRED     = 'expired';

    /** @return string[] */
    public static function all(): array
    {
        return [self::FIRST, self::COMPLETED, self::IN_PROGRESS, self::EXPIRED];
    }
}
