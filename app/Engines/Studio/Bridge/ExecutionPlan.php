<?php

namespace App\Engines\Studio\Bridge;

/**
 * STUDIO888 · Bridge — the plan produced from a resolved selection + operation.
 *
 * The intermediate between "what to change" (Selection + Operation, semantic
 * model) and "how to project it" (ProjectionRequest, renderer-neutral). It
 * carries the resolved target, the field path(s), the desired after-values, the
 * before-values (read from the semantic StudioElement), and the confidence. It
 * names field paths only — never HTML, DOM, or a selector.
 */
final class ExecutionPlan
{
    /**
     * @param string[]            $changedFields     e.g. ['text'] | ['style.color'] | ['visible']
     * @param array<string,mixed> $desiredAfterState path => desired value
     * @param array<string,mixed> $beforeState       path => current value (from the StudioElement)
     */
    public function __construct(
        public readonly string  $opId,
        public readonly string  $operationType,
        public readonly string  $targetId,
        public readonly ?string $property,
        public readonly array   $changedFields,
        public readonly array   $desiredAfterState,
        public readonly array   $beforeState,
        public readonly float   $confidence,
    ) {
    }

    public function primaryField(): ?string
    {
        return $this->changedFields[0] ?? null;
    }

    public function isValid(): bool
    {
        return $this->changedFields !== [] && ($this->changedFields[0] ?? '') !== '';
    }
}
