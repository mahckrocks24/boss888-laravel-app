<?php

namespace App\Engines\Studio\Execution\Handlers;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\OperationHandlerInterface;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Execution\Support\ElementPatch;

/**
 * STUDIO888 · Execution — element-scoped style handler.
 *
 * Handles set_style for a safe, local property set (color, background-color,
 * opacity, font-size, font-weight, text-align). It sets ONLY the target
 * element's style — never a global palette variable. Non-billing. Pure.
 */
final class SetStyleOperationHandler implements OperationHandlerInterface
{
    private const SUPPORTED_PROPERTIES = [
        'color', 'background-color', 'opacity', 'font-size', 'font-weight', 'text-align',
    ];

    public function supportedTypes(): array
    {
        return ['set_style'];
    }

    public function reversible(): bool
    {
        return true;
    }

    public function requiresValidation(): bool
    {
        return true;
    }

    public function usesNormalizedValue(): bool
    {
        return true; // apply canonical value (e.g. red -> #ff0000)
    }

    public function supportsProperty(string $property): bool
    {
        return in_array($property, self::SUPPORTED_PROPERTIES, true);
    }

    public function changedFieldsFor(Operation $op): array
    {
        return ['style.' . (string) $op->property];
    }

    public function expectedChanges(StudioElement $before, Operation $op): array
    {
        return ['style.' . (string) $op->property => $op->value];
    }

    public function apply(StudioElement $before, Operation $op): StudioElement
    {
        return ElementPatch::withStyle($before, (string) $op->property, $op->value);
    }
}
