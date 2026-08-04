<?php

namespace App\Engines\Studio\Selection;

/**
 * STUDIO888 · Selection — a scored candidate.
 *
 * One possible resolution of a query: which element, how sure, and why.
 * `confidence` is 0..1; `reason` is a short human-readable explanation used in
 * clarification prompts and audit ("largest heading by font size").
 */
final class Candidate
{
    public function __construct(
        public readonly string $elementId,
        public readonly float  $confidence,
        public readonly string $reason,
    ) {
    }

    public function toArray(): array
    {
        return ['element_id' => $this->elementId, 'confidence' => $this->confidence, 'reason' => $this->reason];
    }
}
