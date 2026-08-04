<?php

namespace App\Engines\Studio\Execution;

/**
 * STUDIO888 · Execution — a single operation to execute.
 *
 * Renderer-neutral: it names an operation type, a resolved target element id,
 * and (where relevant) a property + value. It carries NO selector, NO DOM node,
 * NO HTML. The target is a StudioElement id resolved by the Selection Engine —
 * never scraped.
 */
final class Operation
{
    public function __construct(
        public readonly string  $type,
        public readonly ?string $targetId = null,
        public readonly ?string $property = null,
        public readonly mixed   $value = null,
        public readonly string  $opId = 'op',
    ) {
    }

    public function withTarget(string $targetId): self
    {
        return new self($this->type, $targetId, $this->property, $this->value, $this->opId);
    }

    public function withValue(mixed $value): self
    {
        return new self($this->type, $this->targetId, $this->property, $value, $this->opId);
    }
}
