<?php

namespace App\Engines\Studio\Execution\Handlers;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\OperationHandlerInterface;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Execution\Support\ElementPatch;

/**
 * STUDIO888 · Execution — lock handler (lock / unlock).
 *
 * Toggles an element's lock state only. `unlock` is the sole write permitted on
 * a currently-locked element (enforced by executor + adapter). Reversible.
 */
final class LockOperationHandler implements OperationHandlerInterface
{
    public function supportedTypes(): array
    {
        return ['lock', 'unlock'];
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
        return ['locked'];
    }

    public function expectedChanges(StudioElement $before, Operation $op): array
    {
        return ['locked' => $op->type === 'lock'];
    }

    public function apply(StudioElement $before, Operation $op): StudioElement
    {
        return ElementPatch::with($before, ['locked' => $op->type === 'lock']);
    }
}
