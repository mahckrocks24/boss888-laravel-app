<?php

namespace App\Engines\Studio\Execution\Handlers;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Contracts\OperationHandlerInterface;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Execution\Support\ElementPatch;

/**
 * STUDIO888 · Execution — text content handler.
 *
 * Handles replace_text / append_text / prepend_text on a single element's
 * text content. Safe, local, non-billing. Pure: returns a new element.
 */
final class TextOperationHandler implements OperationHandlerInterface
{
    public function supportedTypes(): array
    {
        return ['replace_text', 'append_text', 'prepend_text'];
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
        return false; // preserve exact validated text (append/prepend whitespace)
    }

    public function changedFieldsFor(Operation $op): array
    {
        return ['text'];
    }

    public function expectedChanges(StudioElement $before, Operation $op): array
    {
        return ['text' => $this->computeText($before, $op)];
    }

    public function apply(StudioElement $before, Operation $op): StudioElement
    {
        return ElementPatch::with($before, ['text' => $this->computeText($before, $op)]);
    }

    private function computeText(StudioElement $before, Operation $op): string
    {
        $value = (string) $op->value;
        $current = $before->text ?? '';

        return match ($op->type) {
            'append_text'  => $current . $value,
            'prepend_text' => $value . $current,
            default        => $value, // replace_text
        };
    }
}
