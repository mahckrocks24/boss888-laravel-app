<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Support\ArrayGuard;

/**
 * STUDIO888 · Projection — batch transaction boundary.
 *
 * Declares how a batch behaves under partial failure across two dimensions:
 *   atomic            - all-or-nothing; any failure rolls back the whole batch
 *   stopOnFirstFailure - (best-effort only) stop at the first failure vs continue
 *
 * Named policies: atomic() · bestEffortStop() · bestEffortContinue().
 */
final class ProjectionTransactionBoundary
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public readonly bool $atomic,
        public readonly bool $stopOnFirstFailure,
    ) {
    }

    public static function atomic(): self
    {
        return new self(true, true);
    }

    public static function bestEffortStop(): self
    {
        return new self(false, true);
    }

    public static function bestEffortContinue(): self
    {
        return new self(false, false);
    }

    public function label(): string
    {
        if ($this->atomic) {
            return 'atomic';
        }

        return $this->stopOnFirstFailure ? 'best_effort_stop' : 'best_effort_continue';
    }

    public function toArray(): array
    {
        return [
            'schema_version'        => self::SCHEMA_VERSION,
            'atomic'                => $this->atomic,
            'stop_on_first_failure' => $this->stopOnFirstFailure,
        ];
    }

    public static function fromArray(array $a): self
    {
        ArrayGuard::requireSchema($a, self::SCHEMA_VERSION);

        return new self(ArrayGuard::bool($a, 'atomic'), ArrayGuard::bool($a, 'stop_on_first_failure'));
    }
}
