<?php

namespace App\Engines\Studio\Execution\Handlers;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\OperationHandlerInterface;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Execution\Support\ElementPatch;

/**
 * STUDIO888 · Execution — visibility handler (hide / show).
 *
 * Toggles an element's visibility only. Safe, local, non-destructive, reversible.
 */
final class VisibilityOperationHandler implements OperationHandlerInterface
{
    public function supportedTypes(): array
    {
        return ['hide', 'show'];
    }

    public function reversible(): bool
    {
        return true;
    }

    public function requiresValidation(): bool
    {
        return false;
    }

    public function usesNormalizedValue(): bool
    {
        return false;
    }

    public function changedFieldsFor(Operation $op): array
    {
        return ['visible'];
    }

    public function expectedChanges(StudioElement $before, Operation $op): array
    {
        return ['visible' => $op->type === 'show'];
    }

    public function apply(StudioElement $before, Operation $op): StudioElement
    {
        return ElementPatch::with($before, ['visible' => $op->type === 'show']);
    }
}
