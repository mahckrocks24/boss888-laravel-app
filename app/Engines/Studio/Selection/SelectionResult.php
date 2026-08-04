<?php

namespace App\Engines\Studio\Selection;

/**
 * STUDIO888 · Selection — the outcome of a selection.
 *
 * Ambiguity is a FIRST-CLASS result: the engine never guesses. A single-target
 * query with more than one plausible candidate resolves to AMBIGUOUS with
 * clarificationRequired=true, so a later executor can refuse destructive edits
 * below the confidence threshold rather than act on a coin-flip.
 */
final class SelectionResult
{
    public const RESOLVED  = 'resolved';
    public const AMBIGUOUS  = 'ambiguous';
    public const NOT_FOUND = 'not_found';

    /**
     * @param string      $status               one of RESOLVED|AMBIGUOUS|NOT_FOUND
     * @param Candidate[] $candidates            best-first
     * @param bool        $clarificationRequired
     */
    public function __construct(
        public readonly string $status,
        public readonly array  $candidates = [],
        public readonly bool   $clarificationRequired = false,
    ) {
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, [], false);
    }

    public function isResolved(): bool
    {
        return $this->status === self::RESOLVED;
    }

    public function isAmbiguous(): bool
    {
        return $this->status === self::AMBIGUOUS;
    }

    public function topConfidence(): float
    {
        return $this->candidates[0]->confidence ?? 0.0;
    }

    public function top(): ?Candidate
    {
        return $this->candidates[0] ?? null;
    }

    /** The single resolved element id, or null unless exactly-resolved to one. */
    public function resolvedId(): ?string
    {
        return $this->status === self::RESOLVED && count($this->candidates) === 1
            ? $this->candidates[0]->elementId
            : null;
    }

    /** @return string[] */
    public function elementIds(): array
    {
        return array_map(fn (Candidate $c) => $c->elementId, $this->candidates);
    }
}
