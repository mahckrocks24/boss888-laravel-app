<?php

namespace App\Engines\Studio\Execution;

/**
 * STUDIO888 · Execution — execution context (value object).
 *
 * Carries the policy knobs an execution needs without coupling the executor to
 * global config: the confidence threshold a resolved selection must meet, a
 * stricter threshold reserved for destructive operations (none exist in 3A),
 * and a free-form source tag for audit. No Runtime, no config, no I/O.
 */
final class ExecutionContext
{
    public function __construct(
        public readonly float  $confidenceThreshold = 0.60,
        public readonly float  $destructiveThreshold = 0.85,
        public readonly string $source = 'studio',
        public readonly array  $meta = [],
    ) {
    }
}
