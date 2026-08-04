<?php

namespace App\Engines\Studio\Execution\Contracts;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Operation;

/**
 * STUDIO888 · Execution — operation handler (plugin).
 *
 * One handler owns one or more operation types and knows how to produce the
 * updated StudioElement for them. Handlers SELF-DECLARE the types they support
 * and register with the handler registry — there is no central switch. A
 * handler is pure: apply() returns a NEW element and mutates nothing (no DOM,
 * no persistence, no side effects).
 */
interface OperationHandlerInterface
{
    /** @return string[] operation types this handler executes */
    public function supportedTypes(): array;

    /** Whether operations from this handler can be reversed (for Phase 5 undo). */
    public function reversible(): bool;

    /** Whether the operation value must pass the Operation Validator first. */
    public function requiresValidation(): bool;

    /**
     * Whether to apply the validator's NORMALIZED value (true, e.g. colour
     * red -> #ff0000) or the RAW validated value (false, e.g. to preserve
     * leading/trailing whitespace for append/prepend text).
     */
    public function usesNormalizedValue(): bool;

    /**
     * The field paths this operation intends to change (e.g. 'text', 'visible',
     * 'style.color'). Used for verification and changed-field reporting.
     * @return string[]
     */
    public function changedFieldsFor(Operation $op): array;

    /**
     * Expected post-state per field path (path => expected value). Verification
     * compares the actual after-state against this — proof of success.
     * @return array<string,mixed>
     */
    public function expectedChanges(StudioElement $before, Operation $op): array;

    /** Produce the updated element (pure; returns a new immutable element). */
    public function apply(StudioElement $before, Operation $op): StudioElement;
}
