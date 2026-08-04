<?php

namespace App\Engines\Studio\Projection;

/**
 * STUDIO888 · Projection — projection status vocabulary.
 *
 * The renderer-neutral outcome of a projection. Renderer-agnostic: the same
 * status set applies whether the target is iframe HTML, Canvas, SVG, PDF, a
 * video timeline, mobile native, presentation, or print.
 */
final class ProjectionStatus
{
    public const APPLIED       = 'applied';
    public const PARTIAL       = 'partial';
    public const REJECTED      = 'rejected';
    public const STALE_VERSION = 'stale_version';
    public const TARGET_MISSING = 'target_missing';
    public const UNSUPPORTED    = 'unsupported';
    public const FAILED         = 'failed';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::APPLIED, self::PARTIAL, self::REJECTED, self::STALE_VERSION,
            self::TARGET_MISSING, self::UNSUPPORTED, self::FAILED,
        ];
    }

    public static function isSuccess(string $status): bool
    {
        return $status === self::APPLIED || $status === self::PARTIAL;
    }
}
