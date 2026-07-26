<?php

namespace App\Engines\Studio\Guardrail;

/**
 * STUDIO888 Phase K — a single deterministic guardrail violation.
 * Pure data; observational only.
 */
class Violation
{
    public function __construct(
        public readonly string $type,
        public readonly string $severity, // INFO|LOW|MEDIUM|HIGH|CRITICAL
        public readonly string $message,
    ) {}

    public function toArray(): array
    {
        return [
            'type'     => $this->type,
            'severity' => $this->severity,
            'message'  => $this->message,
        ];
    }
}
